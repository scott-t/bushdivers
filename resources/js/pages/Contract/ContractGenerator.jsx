import {
  Alert,
  Avatar,
  Box,
  Button,
  Card,
  CardBody,
  Divider,
  Flex,
  Textarea,
} from '@chakra-ui/react'
import { usePage } from '@inertiajs/react'
import axios from 'axios'
import React, { useEffect, useRef, useState } from 'react'

import ChatBubble from '../../components/elements/experimental/ChatBubble'
import ChatContainer from '../../components/elements/experimental/ChatContainer'
import TypingIndicator from '../../components/elements/experimental/TypingIndicator'
import AppLayout from '../../components/layout/AppLayout'

const AGENT_AVATAR =
  'https://res.cloudinary.com/dpwytlrc2/image/upload/v1750250580/avatar_svg_iye4pz.svg'

const ContractGenerator = ({
  latestConversation,
  conversationLocked,
  conversationMessages,
}) => {
  const { auth } = usePage().props
  const [messages, setMessages] = useState(conversationMessages || [])
  const [conversationId, setConversationId] = useState(
    latestConversation?.id || null
  )
  const [inputText, setInputText] = useState('')
  const [typing, setTyping] = useState(false)
  const [errorText, setErrorText] = useState(null)
  const [showStartNew, setShowStartNew] = useState(false)

  const messagesEndRef = useRef(null)
  const scrollContainerRef = useRef(null)
  const textareaRef = useRef(null)

  const isNearBottom = () => {
    const el = scrollContainerRef.current
    if (!el) return true
    return el.scrollHeight - el.scrollTop - el.clientHeight < 120
  }

  const scrollToBottom = (smooth = true) => {
    messagesEndRef.current?.scrollIntoView({
      behavior: smooth ? 'smooth' : 'auto',
    })
  }

  // Auto-scroll when new messages arrive, but only if user has not scrolled away
  useEffect(() => {
    if (isNearBottom()) {
      scrollToBottom(false)
    }
  }, [messages])

  // When a response finishes, always scroll so the reply is visible
  useEffect(() => {
    if (!typing) {
      scrollToBottom(true)
    }
  }, [typing])

  const handleStartNew = () => {
    setMessages([])
    setConversationId(null)
    setShowStartNew(false)
  }

  const handleSend = async () => {
    const text = inputText.trim()
    if (!text || typing) return

    setInputText('')
    setErrorText(null)

    setMessages((prev) => [...prev, { role: 'user', content: text }])

    setTyping(true)

    try {
      const response = await axios.post('/api/contracts/experimental/chat', {
        message: text,
        conversation_id: conversationId,
      })

      if (response.status === 200) {
        setConversationId(response.data.conversation_id)
        setMessages((prev) => [
          ...prev,
          { role: 'assistant', content: response.data.message },
        ])
      }
    } catch (e) {
      setErrorText('We seem to have run into a problem.')
    }

    setTyping(false)
  }

  const handleKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault()
      handleSend()
    }
  }

  const handleInputChange = (e) => {
    setInputText(e.target.value)

    // Clear error when user starts typing again
    if (errorText) {
      setErrorText(null)
    }

    // Auto-grow textarea up to 160px
    const el = textareaRef.current
    if (el) {
      el.style.height = 'auto'
      el.style.height = `${Math.min(el.scrollHeight, 160)}px`
    }
  }

  // Empty state — no conversation started
  if (!messages.length && !showStartNew) {
    return (
      <Card>
        <CardBody>
          <ChatContainer side="left">
            <Avatar size="sm" name="agent" src={AGENT_AVATAR} />
            <ChatBubble>
              Hey! I hear you&apos;re looking for some work? Want me to find you
              a dispatch?
            </ChatBubble>
          </ChatContainer>
          <Flex mt={4} justify="center">
            <Button size="sm" onClick={() => setShowStartNew(true)}>
              Start New Conversation
            </Button>
          </Flex>
        </CardBody>
      </Card>
    )
  }

  return (
    <Card>
      <CardBody>
        {errorText && <Alert status="error">{errorText}</Alert>}

        {conversationLocked && (
          <Alert status="info" mb={4}>
            This conversation is locked because a new PIREP has been filed
            since. Start a new conversation to continue.
          </Alert>
        )}

        <Box
          ref={scrollContainerRef}
          maxH="60vh"
          overflowY="auto"
          mb={4}
          className="chat-thread"
          display="flex"
          flexDirection="column"
          gap={4}
        >
          {messages.map((msg, idx) => (
            <ChatContainer
              key={idx}
              side={msg.role === 'user' ? 'right' : 'left'}
            >
              {msg.role === 'assistant' && (
                <Avatar size="sm" name="agent" src={AGENT_AVATAR} />
              )}
              <ChatBubble isUser={msg.role === 'user'}>
                <Box whiteSpace="pre-wrap">{msg.content}</Box>
              </ChatBubble>
              {msg.role === 'user' && (
                <Avatar size="sm" name={auth.user.name} />
              )}
            </ChatContainer>
          ))}
          {typing && (
            <ChatContainer side="left">
              <Avatar size="sm" name="agent" src={AGENT_AVATAR} />
              <ChatBubble>
                <TypingIndicator />
              </ChatBubble>
            </ChatContainer>
          )}
          <div ref={messagesEndRef} />
        </Box>

        <Divider mb={4} />

        {!conversationLocked ? (
          <Flex gap={3} align="flex-end">
            <Textarea
              ref={textareaRef}
              value={inputText}
              onChange={handleInputChange}
              onKeyDown={handleKeyDown}
              placeholder="Request a dispatch..."
              size="sm"
              rows={1}
              resize="none"
              flex={1}
              minH="40px"
              maxH="160px"
              overflowY="auto"
            />
            <Button
              size="sm"
              onClick={handleSend}
              isLoading={typing}
              isDisabled={!inputText.trim()}
              flexShrink={0}
            >
              Send
            </Button>
          </Flex>
        ) : (
          <Flex justify="center">
            <Button size="sm" onClick={handleStartNew}>
              Start New Conversation
            </Button>
          </Flex>
        )}
      </CardBody>
    </Card>
  )
}

ContractGenerator.layout = (page) => (
  <AppLayout
    children={page}
    title="Contract Generator"
    heading="Contract Generator"
  />
)

export default ContractGenerator
