import React from 'react'
import PageTitle from '../../Shared/Navigation/PageTitle'
import Layout from '../../Shared/Layout'

const MyFinances = ({ accounts, balance }) => {
  const renderTransactionType = (transactionType) => {
    switch (transactionType) {
      case 1:
        return 'Contract Pay'
      case 2:
        return 'Jumpseat'
      case 3:
        return 'Contract Cancellation'
      case 4:
        return 'Refuel Penalty'
      case 5:
        return 'Bonus Pay (i.e returning aircraft to hub)'
    }
  }

  return (
    <div>
      <PageTitle title="My Finances" />
      <div className="md:w-1/2">
        <div className="bg-white shadow rounded p-4 mt-2">
          <div className="text-lg">Current Balance</div>
          <div className="text-xl">${balance}</div>
        </div>
        {accounts && accounts.map((entry) => (
          <div key={entry.id} className="bg-white shadow rounded p-4 mt-2 flex justify-between">
            <div>
              ${entry.total} <br />
              <span className="text-sm">
                {renderTransactionType(entry.type)}
              </span>
            </div>
            <div>{entry.created_at}</div>
          </div>
        ))}
      </div>
    </div>
  )
}

MyFinances.layout = page => <Layout children={page} title="My Finances" />

export default MyFinances
