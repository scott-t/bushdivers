import React from 'react'

const PirepFinancials = (props) => {
  const renderCompanyTransactionType = (transaction) => {
    switch (transaction) {
      case 5:
        return 'Ground Handling'
      case 6:
        return 'Landing Fee'
      case 7:
        return 'Fuel Cost'
      case 8:
        return 'Contract Income'
      case 9:
        return 'Pilot Pay'
    }
  }

  return (
    <div className="bg-white rounded shadow p-4 my-2 mx-2 overflow-x-auto">
      <div className="text-lg">Financials</div>
      <table className="table table-condensed table-auto">
        <thead>
        <tr>
          <th>Transaction</th>
          <th>Total</th>
        </tr>
        </thead>
        <tbody>
        {props.company.map((company) => (
          <tr key={company.id}>
            <td>{renderCompanyTransactionType(company.transaction_type)} - {company.memo}</td>
            <td className={company.total < 0 && 'text-red-500'}>${company.total}</td>
          </tr>
        ))}
        </tbody>
      </table>
      <div className="text-right">
        <div>Total: <span className={props.total < 0 && 'text-red-500'}>${props.total}</span></div>
      </div>
    </div>
  )
}

export default PirepFinancials
